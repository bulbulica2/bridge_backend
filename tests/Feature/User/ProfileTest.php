<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
  use RefreshDatabase;

  public function test_guest_is_rejected(): void
  {
    $user = User::factory()->create();

    $this->getJson("/users/$user->id")->assertUnauthorized();
    $this->patchJson('/api/user', ['name' => 'Hacker'])->assertUnauthorized();

    $this->assertSame($user->name, $user->fresh()->name);
  }

  public function test_show_returns_the_public_profile_without_email(): void
  {
    $viewer = User::factory()->create();
    $other = User::factory()->create(['description' => 'Plays a strong club.']);

    $this->actingAs($viewer)->getJson("/users/$other->id")
      ->assertOk()
      ->assertJsonPath('status', 200)
      ->assertExactJson([
        'status' => 200,
        'message' => 'User retrieved successfully.',
        'data' => [
          'id' => $other->id,
          'name' => $other->name,
          'username' => $other->username,
          'description' => 'Plays a strong club.',
        ],
      ])
      ->assertJsonMissingPath('data.email');
  }

  public function test_show_unknown_user_is_404(): void
  {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->getJson('/users/999')->assertNotFound();
  }

  public function test_a_user_updates_their_own_name_and_description(): void
  {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/user', ['name' => 'New Name', 'description' => 'Likes 2/1.'])
      ->assertOk()
      ->assertJsonPath('data.id', $user->id)
      ->assertJsonPath('data.name', 'New Name')
      ->assertJsonPath('data.description', 'Likes 2/1.')
      ->assertJsonPath('data.email', $user->email);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name', 'description' => 'Likes 2/1.']);
  }

  public function test_description_can_be_cleared_and_fields_are_optional(): void
  {
    $user = User::factory()->create(['name' => 'Kept']);

    $this->actingAs($user)->patchJson('/api/user', ['description' => null])->assertOk();

    $user->refresh();
    $this->assertNull($user->description);
    $this->assertSame('Kept', $user->name);
  }

  public function test_update_cannot_touch_another_user_username_email_or_admin_flag(): void
  {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/user', [
      'id' => $other->id,
      'name' => 'Mine',
      'username' => 'taken_over',
      'email' => 'new@example.com',
      'is_admin' => true,
    ])->assertOk();

    $user->refresh();
    $this->assertSame('Mine', $user->name);
    $this->assertNotSame('taken_over', $user->username);
    $this->assertNotSame('new@example.com', $user->email);
    $this->assertFalse($user->is_admin);

    $this->assertSame($other->name, $other->fresh()->name);
  }

  public function test_invalid_update_is_rejected(): void
  {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/user', ['name' => '', 'description' => str_repeat('a', 1001)])
      ->assertUnprocessable()
      ->assertJsonValidationErrors(['name', 'description']);
  }
}
