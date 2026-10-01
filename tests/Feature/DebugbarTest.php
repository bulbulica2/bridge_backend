<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Barryvdh\Debugbar\LaravelDebugbar;
use Tests\TestCase;

/**
 * `AppServiceProvider` force-enables debugbar locally, unless `.env` says
 * `DEBUGBAR_ENABLED=false`, and never in tests.
 */
class DebugbarTest extends TestCase
{
  public function test_it_is_off_in_tests(): void
  {
    $this->assertFalse(app(LaravelDebugbar::class)->isEnabled());
  }

  public function test_it_is_on_locally_when_the_key_is_unset(): void
  {
    $this->registerLocally(null);

    $this->assertTrue(app(LaravelDebugbar::class)->isEnabled());
  }

  public function test_debugbar_enabled_false_keeps_it_off_locally(): void
  {
    $this->registerLocally(false);

    $this->assertFalse(app(LaravelDebugbar::class)->isEnabled());
  }

  /**
   * Run the provider again as it would run with `APP_ENV=local` and
   * `DEBUGBAR_ENABLED` as given (null: not in `.env`).
   */
  private function registerLocally(?bool $enabled): void
  {
    config(['debugbar.enabled' => $enabled]);
    $this->app['env'] = 'local';

    (new AppServiceProvider($this->app))->register();
  }
}
