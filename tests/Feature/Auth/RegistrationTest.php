<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
  use RefreshDatabase;

  public function test_new_users_can_register(): void
  {
    $response = $this->post('/register', [
      'name' => 'Test User',
      'username' => 'testuser',
      'email' => 'test@example.com',
      'password' => 'password',
      'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertNoContent();
  }

  public function test_name_and_username_are_short_enough_to_broadcast(): void
  {
    $register = fn (string $name, string $username) => $this->postJson('/register', [
      'name' => $name,
      'username' => $username,
      'email' => 'test@example.com',
      'password' => 'password',
      'password_confirmation' => 'password',
    ]);

    $register(str_repeat('n', User::NAME_MAX + 1), str_repeat('u', User::USERNAME_MAX + 1))
      ->assertUnprocessable()
      ->assertJsonValidationErrors(['name', 'username']);
    $this->assertGuest();

    // the limits count characters, not bytes
    $register(str_repeat('ă', User::NAME_MAX), str_repeat('ș', User::USERNAME_MAX))->assertNoContent();
    $this->assertAuthenticated();
  }
}
