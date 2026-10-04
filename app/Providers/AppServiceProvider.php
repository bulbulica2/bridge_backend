<?php

namespace App\Providers;

use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    // RUNNING.md) has to be checked here or it does nothing
    if ($this->app->environment('local') && config('debugbar.enabled') !== false) {
      Debugbar::enable();
    }
  }

  /**
   * Bootstrap any application services.
   */
  public function boot(): void
  {
    ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
      return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
    });

    // POST /tables/{table}/messages: 10 messages per 30 seconds per player
    RateLimiter::for('board-messages', fn (Request $request) => Limit::perSecond(10, 30)->by($request->user()->id));
  }
}
