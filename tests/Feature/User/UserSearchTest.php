<?php

namespace Tests\Feature\User;

use App\Http\Controllers\UserController;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserSearchTest extends TestCase
{
  use RefreshDatabase;

  private User $viewer;

  protected function setUp(): void
  {
    parent::setUp();

    $this->viewer = User::factory()->create(['username' => 'viewer', 'name' => 'The Viewer']);
  }

  private function search(string $text): TestResponse
  {
    return $this->actingAs($this->viewer)->getJson('/users?'.http_build_query(['search' => $text]));
  }

  /** @return list<string> */
  private function usernames(TestResponse $response): array
  {
    return array_column($response->assertOk()->json('data'), 'username');
  }

  public function test_guest_is_rejected(): void
  {
    $this->getJson('/users?search=ann')->assertUnauthorized();
  }

  public function test_search_needs_at_least_two_characters(): void
  {
    $this->actingAs($this->viewer)->getJson('/users')
      ->assertUnprocessable()->assertJsonValidationErrors('search');
    $this->search('a')->assertUnprocessable()->assertJsonValidationErrors('search');
    $this->search('an')->assertOk();
  }

  public function test_matches_username_and_name_case_insensitively(): void
  {
    User::factory()->create(['username' => 'annabel', 'name' => 'Belle Smith']);
    User::factory()->create(['username' => 'bob', 'name' => 'Joanna Jones']);
    User::factory()->create(['username' => 'carl', 'name' => 'Carl King']);

    $this->assertSame(['annabel', 'bob'], $this->usernames($this->search('ANN')));
    $this->assertSame(['annabel'], $this->usernames($this->search('smi')));
  }

  public function test_never_matches_on_email(): void
  {
    User::factory()->create(['username' => 'dave', 'name' => 'Dave', 'email' => 'secret.address@example.com']);

    $this->assertSame([], $this->usernames($this->search('secret.address')));
    $this->assertSame([], $this->usernames($this->search('example.com')));
  }

  public function test_wildcards_are_matched_literally(): void
  {
    User::factory()->create(['username' => 'eve', 'name' => 'Eve']);
    User::factory()->create(['username' => 'fifty_percent', 'name' => '50% Fred']);

    $this->assertSame([], $this->usernames($this->search('%%')));
    $this->assertSame(['fifty_percent'], $this->usernames($this->search('0%')));
    $this->assertSame(['fifty_percent'], $this->usernames($this->search('y_p')));
  }

  public function test_results_are_public_profiles_without_email(): void
  {
    $ann = User::factory()->create(['username' => 'ann', 'name' => 'Ann', 'description' => 'Plays a strong club.']);

    $this->search('ann')
      ->assertOk()
      ->assertExactJson([
        'status' => 200,
        'message' => 'Users retrieved successfully.',
        'data' => [[
          'id' => $ann->id,
          'name' => 'Ann',
          'username' => 'ann',
          'description' => 'Plays a strong club.',
          'is_robot' => false,
          'seated' => false,
        ]],
      ]);
  }

  public function test_returns_at_most_ten_results(): void
  {
    User::factory()->count(UserController::SEARCH_LIMIT + 2)
      ->sequence(fn ($sequence) => ['username' => "player$sequence->index"])
      ->create();

    $this->assertCount(UserController::SEARCH_LIMIT, $this->usernames($this->search('player')));
  }

  public function test_seated_is_true_only_for_users_holding_a_seat(): void
  {
    $seated = User::factory()->create(['username' => 'seated_one']);
    User::factory()->create(['username' => 'standing_one']);
    TableSeat::factory()->create(['user_id' => $seated->id]);

    $data = collect($this->search('_one')->assertOk()->json('data'))->pluck('seated', 'username');

    $this->assertSame(['seated_one' => true, 'standing_one' => false], $data->all());
  }

  public function test_is_throttled(): void
  {
    for ($i = 0; $i < 30; $i++) {
      $this->search('ann')->assertOk();
    }

    $this->search('ann')->assertTooManyRequests();
  }
}
