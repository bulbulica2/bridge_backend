<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
  use RefreshDatabase;

  public function test_reset_password_link_can_be_requested(): void
  {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
  }

  public function test_the_reset_mail_comes_from_bridge4u_and_links_to_the_spa(): void
  {
    // the defaults, as on a checkout whose .env doesn't name the app
    $this->assertSame('Bridge4U', config('app.name'));
    $this->assertSame('Bridge4U', config('mail.from.name'));

    $user = User::factory()->create();

    // sent for real, through phpunit.xml's array mailer
    $this->post('/forgot-password', ['email' => $user->email]);

    $messages = app('mailer')->getSymfonyTransport()->messages();
    $this->assertCount(1, $messages);
    $mail = $messages[0]->getOriginalMessage();

    $this->assertSame('Reset your Bridge4U password', $mail->getSubject());
    $this->assertSame('Bridge4U', $mail->getFrom()[0]->getName());
    $this->assertSame($user->email, $mail->getTo()[0]->getAddress());

    $body = $mail->getTextBody();
    $this->assertStringContainsString('your Bridge4U account', $body);
    $this->assertStringContainsString(config('app.frontend_url').'/password-reset/', $body);
    $this->assertStringContainsString('email='.$user->email, $body);
    $this->assertStringContainsString('expire in 60 minutes', $body);
    $this->assertStringNotContainsString('Laravel', $mail->getSubject().$body.$mail->getHtmlBody());
  }

  public function test_password_can_be_reset_with_valid_token(): void
  {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (object $notification) use ($user) {
      $response = $this->post('/reset-password', [
        'token' => $notification->token,
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
      ]);

      $response
        ->assertSessionHasNoErrors()
        ->assertStatus(200);

      return true;
    });
  }
}
