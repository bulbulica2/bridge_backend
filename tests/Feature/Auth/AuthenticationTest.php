<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
  use RefreshDatabase;

  public function test_users_can_authenticate_using_the_login_screen(): void
  {
    $user = User::factory()->create();

    $response = $this->post('/login', [
      'email' => $user->email,
      'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertNoContent();
  }

  public function test_users_can_not_authenticate_with_invalid_password(): void
  {
    $user = User::factory()->create();

    $this->post('/login', [
      'email' => $user->email,
      'password' => 'wrong-password',
    ]);

    $this->assertGuest();
  }

  public function test_a_sixth_login_after_five_failures_is_throttled(): void
  {
    Event::fake([Lockout::class]);
    $user = User::factory()->create();

    for ($attempt = 1; $attempt <= 5; $attempt++) {
      $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', __('auth.failed'));
    }

    Event::assertNotDispatched(Lockout::class);

    // even the right password is refused until the lockout runs out
    $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
      ->assertUnprocessable()
      ->assertJsonPath('errors.email.0', fn (string $message) => str_starts_with($message, 'Too many login attempts.'));

    $this->assertGuest();
    Event::assertDispatched(Lockout::class);
  }

  public function test_users_can_logout(): void
  {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertNoContent();
  }
}
