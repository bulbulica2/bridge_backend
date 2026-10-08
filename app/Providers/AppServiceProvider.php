<?php

namespace App\Providers;

use App\Services\QueueHealthService;
use App\Solvers\DdsSolver;
use App\Solvers\DoubleDummySolver;
use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
  /**
   * Register any application services.
   */
  public function register(): void
  {
    // enable() bypasses debugbar's own "off when testing" check, and the
    // injected HTML breaks assertNoContent(), so only force it on locally.
    // It also overrides the config, so DEBUGBAR_ENABLED=false (see
    // RUNNING.md) has to be checked here or it does nothing. Never in the
    // console, which enable() would bypass too: in queue:work or
    // reverb:start it collects every transaction (with a backtrace, and no
    // limit) for a page that never comes, until the worker reaches
    // --memory and stops (#127). `php artisan serve`'s requests aren't
    // console, so they keep it
    if ($this->app->environment('local') && ! $this->app->runningInConsole() && config('debugbar.enabled') !== false) {
      Debugbar::enable();
    }

    // one per process: a worker remembers its last heartbeat
    $this->app->singleton(QueueHealthService::class);

    // the double dummy solver only when DDS_LIBRARY names the library: with
    // none bound, nothing is solved (DoubleDummyService::available())
    if (filled(config('bridge.dds_library'))) {
      $this->app->singleton(DoubleDummySolver::class, fn () => new DdsSolver(
        config('bridge.dds_library'),
        config('bridge.dds_memory_mb'),
        config('bridge.dds_threads'),
      ));
    }
  }

  /**
   * Bootstrap any application services.
   */
  public function boot(): void
  {
    // Laravel's own subject ("Reset Password Notification") doesn't say
    // whose password; name the app (Bridge4U), as the sender and the
    // signature already do through config('app.name'). The link goes to
    // the SPA's reset page
    ResetPassword::toMailUsing(function (object $notifiable, string $token) {
      $url = config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
      $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

      return (new MailMessage)
        ->subject(__('Reset your :app password', ['app' => config('app.name')]))
        ->line(__('You are receiving this email because we received a password reset request for your :app account.', ['app' => config('app.name')]))
        ->action(__('Reset Password'), $url)
        ->line(__('This password reset link will expire in :count minutes.', ['count' => $minutes]))
        ->line(__('If you did not request a password reset, no further action is required.'));
    });

    // POST /tables/{table}/messages: 10 messages per 30 seconds per player
    RateLimiter::for('board-messages', fn (Request $request) => Limit::perSecond(10, 30)->by($request->user()->id));
  }
}
