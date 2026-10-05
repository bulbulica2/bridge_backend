<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Barryvdh\Debugbar\LaravelDebugbar;
use Tests\TestCase;

/**
 * `AppServiceProvider` force-enables debugbar locally, unless `.env` says
 * `DEBUGBAR_ENABLED=false`, and never in tests or the console: in a
 * long-running `queue:work` it kept every transaction it saw until the
 * worker hit its memory limit and stopped (#127).
 */
class DebugbarTest extends TestCase
{
  public function test_it_is_off_in_tests(): void
  {
    $this->assertFalse(app(LaravelDebugbar::class)->isEnabled());
  }

  public function test_it_is_on_locally_when_the_key_is_unset(): void
  {
    $this->registerLocally(null, console: false);

    $this->assertTrue(app(LaravelDebugbar::class)->isEnabled());
  }

  public function test_debugbar_enabled_false_keeps_it_off_locally(): void
  {
    $this->registerLocally(false, console: false);

    $this->assertFalse(app(LaravelDebugbar::class)->isEnabled());
  }

  public function test_it_stays_off_in_the_console_locally(): void
  {
    $this->registerLocally(null, console: true);

    $this->assertFalse(app(LaravelDebugbar::class)->isEnabled());
  }

  /**
   * Run the provider again as it would run with `APP_ENV=local` and
   * `DEBUGBAR_ENABLED` as given (null: not in `.env`), in an artisan
   * command or in a request (`php artisan serve`'s, which isn't console).
   */
  private function registerLocally(?bool $enabled, bool $console): void
  {
    config(['debugbar.enabled' => $enabled]);
    $this->app['env'] = 'local';
    (fn () => $this->isRunningInConsole = $console)->call($this->app);

    (new AppServiceProvider($this->app))->register();
  }
}
